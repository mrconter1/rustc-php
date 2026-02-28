// stdout: 2
// stdout: 4
// stdout: 6
// stdout: 8
// stdout: 10

fn main() {
    let factor = 2;
    let double = |x: i32| x * factor;
    for i in 1..6 {
        println!("{}", double(i));
    }
}
