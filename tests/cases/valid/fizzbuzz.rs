// stdout: 1
// stdout: 2
// stdout: Fizz
// stdout: 4
// stdout: Buzz
// stdout: Fizz
// stdout: 7
// stdout: 8
// stdout: Fizz
// stdout: Buzz
// stdout: 11
// stdout: Fizz
// stdout: 13
// stdout: 14
// stdout: FizzBuzz
fn main() {
    let mut i: i32 = 1;
    while i <= 15 {
        if i % 15 == 0 {
            println!("FizzBuzz");
        } else if i % 3 == 0 {
            println!("Fizz");
        } else if i % 5 == 0 {
            println!("Buzz");
        } else {
            println!("{}", i);
        }
        i = i + 1;
    }
}
