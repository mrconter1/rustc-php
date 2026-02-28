// stdout: 1
// stdout: 3
// stdout: 5
// stdout: 7
// stdout: 9
fn main() {
    let mut i: i32 = 0;
    while i < 10 {
        i = i + 1;
        if i % 2 == 0 {
            continue;
        }
        println!("{}", i);
    }
}
