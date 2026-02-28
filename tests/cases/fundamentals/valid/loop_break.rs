// stdout: 5
fn main() {
    let mut i: i32 = 0;
    loop {
        if i == 5 {
            break;
        }
        i = i + 1;
    }
    println!("{}", i);
}
